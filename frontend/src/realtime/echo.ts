import { request } from '@/api/client'

export interface RealtimeConfig { key: string; host: string; port: number; scheme: 'http' | 'https' }

export interface RealtimeHandlers {
  onItemChanged: (payload: { key: string; event: string; actor_id: number | null }) => void
  onInboxChanged: (payload: { workspace_id: number }) => void
}

/** A live connection; `disconnect` leaves the channels and closes the socket. */
export interface RealtimeConnection { disconnect: () => void }

type Env = Partial<Record<'VITE_REVERB_APP_KEY' | 'VITE_REVERB_HOST' | 'VITE_REVERB_PORT' | 'VITE_REVERB_SCHEME', string>>

/** The Reverb settings from the Vite env, or null when no key is set: realtime is then simply off. */
export function realtimeConfig(env: Env = import.meta.env): RealtimeConfig | null {
  const key = env.VITE_REVERB_APP_KEY?.trim()
  if (!key) return null
  const scheme = env.VITE_REVERB_SCHEME === 'https' ? 'https' : 'http'
  const port = Number(env.VITE_REVERB_PORT) || (scheme === 'https' ? 443 : 8080)
  return { key, host: env.VITE_REVERB_HOST?.trim() || window.location.hostname, port, scheme }
}

interface AuthData { auth: string; channel_data?: string }

/**
 * Opens the socket and listens on the workspace's and the user's private
 * channels. Echo and pusher-js are loaded here, on demand, so they cost
 * nothing when realtime is off. Channel access is granted by the API through
 * the same cookie session (and XSRF header) as every other request.
 */
export async function connectRealtime(
  config: RealtimeConfig, workspaceId: number, userId: number, handlers: RealtimeHandlers,
): Promise<RealtimeConnection> {
  const [{ default: Echo }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])

  const echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: config.key,
    wsHost: config.host,
    wsPort: config.port,
    wssPort: config.port,
    forceTLS: config.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: AuthData | null) => void) => {
        request<AuthData>('POST', '/broadcasting/auth', { body: { socket_id: socketId, channel_name: channel.name } })
          .then((data) => callback(null, data))
          .catch((err: unknown) => callback(err instanceof Error ? err : new Error('Channel authorisation failed'), null))
      },
    }),
  })

  echo.private(`workspace.${workspaceId}`).listen('.item.changed', handlers.onItemChanged)
  echo.private(`user.${userId}`).listen('.inbox.changed', handlers.onInboxChanged)

  return { disconnect: () => echo.disconnect() }
}
