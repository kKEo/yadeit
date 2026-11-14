import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable without signing in. */
    public?: boolean
    /** Sign-in screens: signed-in users are sent home instead. */
    guestOnly?: boolean
  }
}
