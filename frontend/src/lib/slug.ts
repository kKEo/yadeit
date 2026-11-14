const SLUG_MAX_LENGTH = 40

/** Same shape the API accepts: lowercase letters and digits in hyphen-separated groups. */
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/** Derives a URL slug from a name: "Zażółć Gęślą!" → "zazolc-gesla". */
export function slugify(name: string): string {
  return name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/ł/g, 'l')
    .replace(/Ł/g, 'L')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .slice(0, SLUG_MAX_LENGTH)
    .replace(/^-+|-+$/g, '')
}

export function isValidSlug(slug: string): boolean {
  return slug.length >= 2 && slug.length <= SLUG_MAX_LENGTH && SLUG_PATTERN.test(slug)
}
