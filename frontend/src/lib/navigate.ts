/** Leaves the app for another site (the payment page). In one place so tests can stand in for it. */
export function leaveTo(url: string): void {
  window.location.assign(url)
}
