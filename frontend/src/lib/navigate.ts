/** Hands the browser a file to save. In one place so tests can stand in for it. */
export function saveFile(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.append(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/** Leaves the app for another site (the payment page). In one place so tests can stand in for it. */
export function leaveTo(url: string): void {
  window.location.assign(url)
}
