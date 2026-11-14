/**
 * The address of an embeddable preview for a design link, or null when the
 * link is not one we know how to preview. Only Figma is supported: its embed
 * page takes the original link as a parameter.
 */
export function designEmbedUrl(link: string): string | null {
  let url: URL
  try {
    url = new URL(link)
  } catch {
    return null
  }
  const isFigma = url.protocol === 'https:' && (url.hostname === 'figma.com' || url.hostname === 'www.figma.com')
  if (!isFigma || !/^\/(file|design|proto|board)\//.test(url.pathname)) return null
  return `https://www.figma.com/embed?embed_host=yadeit&url=${encodeURIComponent(url.toString())}`
}
