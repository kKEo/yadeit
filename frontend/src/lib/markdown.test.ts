import { describe, expect, it } from 'vitest'
import { renderMarkdown } from './markdown'

describe('renderMarkdown', () => {
  it('renders Markdown', () => {
    const html = renderMarkdown('# Title\n\nSome **bold** text and `code`.')
    expect(html).toContain('<h1>Title</h1>')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('<code>code</code>')
  })

  it('strips script tags and inline event handlers', () => {
    const html = renderMarkdown('Hello <script>alert(1)</script><img src="x" onerror="alert(2)">')
    expect(html).not.toContain('<script')
    expect(html).not.toContain('alert(1)')
    expect(html).not.toContain('onerror')
  })

  it('removes javascript: links but keeps the text', () => {
    const html = renderMarkdown('[click me](javascript:alert(1)) and <a href="JaVaScRiPt:alert(2)">raw</a>')
    expect(html.toLowerCase()).not.toContain('javascript:')
    expect(html).toContain('click me')
    expect(html).toContain('raw')
  })

  it('opens web links in a new tab without handing over the opener', () => {
    const html = renderMarkdown('[docs](https://example.com/docs)')
    expect(html).toContain('href="https://example.com/docs"')
    expect(html).toContain('rel="noopener noreferrer"')
    expect(html).toContain('target="_blank"')
  })

  it('returns an empty string for nothing', () => {
    expect(renderMarkdown(null)).toBe('')
    expect(renderMarkdown('')).toBe('')
  })
})
