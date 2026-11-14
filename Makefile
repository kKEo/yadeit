# Documentation site: the landing page (docs/guide/index.html) above the user guide.
#
#   make docs-site              build everything and serve it on http://localhost:8000
#   make docs-site PORT=9000    on another port

DOCS_PORT ?= $(or $(PORT),8000)
SITE      := site

.PHONY: docs-build docs-site

## Build the user guide with mdBook and put the landing page at the site root.
docs-build:
	@command -v mdbook >/dev/null || { echo "mdbook is not installed: https://rust-lang.github.io/mdBook/guide/installation.html"; exit 1; }
	mdbook build docs/guide/user
	cp docs/guide/index.html $(SITE)/index.html

## Serve the built site locally: landing page at /, user guide at /user/.
docs-site: docs-build
	@echo "Documentation at http://localhost:$(DOCS_PORT)/  (Ctrl+C to stop)"
	python3 -m http.server $(DOCS_PORT) --bind 127.0.0.1 --directory $(SITE)
