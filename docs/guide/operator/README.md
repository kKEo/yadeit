# Yadeit operator guide

This guide is for the people who install, configure and keep a Yadeit
installation running, and for developers who need to know how it works
inside. It assumes you can use a shell, Docker Compose and a text editor,
and that you know what an environment variable and a reverse proxy are. It
does not assume you know Laravel or Vue.

![The parts of a Yadeit installation: browsers, agents and MCP clients reach one address served by the app container; the app serves the web app and the API and talks to PostgreSQL; the worker sends emails and webhooks from the queue; the scheduler runs timed jobs; the backup job dumps the database and archives uploads; Reverb pushes realtime signals.](images/architecture.svg)

## Where to go

| Part | Read it when you need to… |
|---|---|
| [Part I: How it works](intro/README.md) | understand the processes, the data and what runs in the background before you change anything |
| [Part II: Install and deploy](install/README.md) | put Yadeit on a server, set up HTTPS, or run it on your laptop |
| [Part III: Configuration reference](config/README.md) | look up a setting, its default and what breaks when it is wrong |
| [Part IV: Monitoring](monitoring/README.md) | know whether the installation is healthy and get told when it is not |
| [Part V: Operations](operations/README.md) | back up, restore, upgrade, manage workspaces and people, or fix a problem |
| [Appendix](appendix/README.md) | look up a word |

If you have ten minutes, read [System overview](intro/overview.md) and
[Deploy with Docker Compose](install/docker.md).

## Conventions

- Commands are shown in fenced blocks. Where a command runs inside the
  production stack, the guide writes it in full once and then shortens it
  with an alias, `dc`:

  ```bash
  alias dc='docker compose --env-file .env.production -f compose.prod.yaml'
  dc exec app php artisan app:health
  ```

- File paths are relative to the repository root, for example
  `backend/config/ai.php`.
- Every default value quoted here comes from the code. When the guide and the
  code disagree, the code wins: please report it.
- **Operator** means a person with the operator role on the installation
  (granted with `php artisan operator:grant`). A workspace **admin** or
  **owner** only manages their own workspace.

> **Looking for something else?** People who use Yadeit day to day should read
> the [user guide](../user/README.md). The load and flow test with simulated
> teams is described in
> [docs/traffic-lab.md](https://github.com/kKEo/yadeit/blob/master/docs/traffic-lab.md).
