import DOMPurify from 'dompurify'
import { marked } from 'marked'

let hooked = false

/** Links open in a new tab and never hand the opener over. Registered once. */
function ensureHooks() {
  if (hooked) return
  hooked = true
  DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A' && node.hasAttribute('href')) {
      node.setAttribute('target', '_blank')
      node.setAttribute('rel', 'noopener noreferrer')
    }
  })
}

/**
 * Markdown → HTML that is safe to put in `v-html`: scripts, event handlers and
 * `javascript:`/`data:` links are removed. The only place Markdown becomes HTML.
 */
export function renderMarkdown(source: string | null | undefined): string {
  if (!source) return ''
  ensureHooks()
  const html = marked.parse(source, { async: false, gfm: true, breaks: true })
  return DOMPurify.sanitize(html, {
    USE_PROFILES: { html: true },
    FORBID_TAGS: ['style', 'form', 'input', 'button', 'textarea', 'select', 'iframe'],
    FORBID_ATTR: ['style'],
    ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|#|\/(?!\/))/i,
  })
}
