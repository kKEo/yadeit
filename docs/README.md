# Documentation

| What | Where |
|---|---|
| Landing page of the documentation site | [guide/index.html](guide/index.html) |
| User guide (for everyone who uses Yadeit) | [guide/user/](guide/user/README.md) |
| Running Yadeit in production | [deployment.md](deployment.md) |
| The rebuild plan | [plan.md](plan.md) |

The user guide is a GitBook space (`guide/user/.gitbook.yaml`) that mdBook can
also build:

```bash
make docs-site                    # landing page and guide at http://localhost:8000
make docs-build                   # only build into site/
mdbook serve docs/guide/user      # live-reloading preview of the guide alone
```

CI builds the site on every push and pull request (job `docs`) and publishes
it to GitHub Pages from `master` (job `docs-deploy`), at
<https://kkeo.github.io/yadeit/>. Publishing needs **Settings → Pages →
Source: GitHub Actions** in the repository, once.
