/** Suggests a project key from its name: "Web platform" → "WP", "Website" → "WEB". */
export function suggestProjectKey(name: string): string {
  const words = name
    .normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[łŁ]/g, 'L')
    .toUpperCase().split(/[^A-Z0-9]+/).filter(Boolean)
  if (words.length === 0) return ''
  const raw = words.length === 1 ? words[0].slice(0, 3) : words.map((w) => w[0]).join('').slice(0, 5)
  // Keys start with a letter and have at least two characters.
  const key = raw.replace(/^[0-9]+/, '')
  return key.length >= 2 ? key : (key + words[0].replace(/^[0-9]+/, '').slice(key.length, 3)).slice(0, 3)
}

export function isValidProjectKey(key: string): boolean {
  return /^[A-Za-z][A-Za-z0-9]{1,9}$/.test(key)
}
