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
mdbook build docs/guide/user      # writes site/user/
mdbook serve docs/guide/user      # preview at http://localhost:3000
```
