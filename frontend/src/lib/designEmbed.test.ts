import { describe, expect, it } from 'vitest'
import { designEmbedUrl } from './designEmbed'

describe('designEmbedUrl', () => {
  it('builds the Figma embed address for design, file, prototype and board links', () => {
    const link = 'https://www.figma.com/design/abc123/Checkout?node-id=1-2'

    expect(designEmbedUrl(link)).toBe(`https://www.figma.com/embed?embed_host=yadeit&url=${encodeURIComponent(link)}`)
    expect(designEmbedUrl('https://figma.com/proto/x/Flow')).not.toBeNull()
    expect(designEmbedUrl('https://www.figma.com/board/x/Map')).not.toBeNull()
  })

  it('previews nothing else', () => {
    for (const link of [
      'https://www.figma.com/community', 'http://www.figma.com/design/abc', 'https://figma.com.evil.example/design/abc',
      'https://example.com/?u=https://www.figma.com/design/abc', 'javascript:alert(1)', 'not a url', '',
    ]) {
      expect(designEmbedUrl(link)).toBeNull()
    }
  })
})
