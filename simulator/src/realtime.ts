import type { RealtimeConfig } from './control.ts'

type Listener = (event: string, data: Record<string, unknown>) => void

/**
 * The websocket a browser holds open for realtime updates, spoken directly:
 * Reverb uses the Pusher protocol. Connect, receive a socket id, have the
 * API sign each private channel, subscribe, answer pings.
 */
export class Socket {
  ready = false
  private ws: WebSocket | null = null
  private socketId = ''
  private readonly url: string
  private readonly authorize: (socketId: string, channel: string) => Promise<string>
  private readonly listeners = new Map<string, Listener>()
  private readonly waiting = new Map<string, () => void>()

  constructor(config: RealtimeConfig, authorize: (socketId: string, channel: string) => Promise<string>) {
    this.url = `${config.scheme === 'https' ? 'wss' : 'ws'}://${config.host}:${config.port}/app/${config.key}?protocol=7&client=yadeit-simulator&version=1`
    this.authorize = authorize
  }

  open(): Promise<void> {
    return new Promise((resolve, reject) => {
      const ws = new WebSocket(this.url)
      this.ws = ws
      const timer = setTimeout(() => reject(new Error('The realtime server did not answer within 10 s')), 10_000)

      ws.addEventListener('error', () => { clearTimeout(timer); reject(new Error(`Could not connect to the realtime server at ${this.url.split('/app/')[0]}`)) })
      ws.addEventListener('close', () => { this.ready = false })
      ws.addEventListener('message', (message) => {
        const frame = JSON.parse(String(message.data)) as { event: string; channel?: string; data?: unknown }
        const data = (typeof frame.data === 'string' ? JSON.parse(frame.data) : frame.data ?? {}) as Record<string, unknown>

        if (frame.event === 'pusher:connection_established') {
          this.socketId = String(data.socket_id)
          this.ready = true
          clearTimeout(timer)
          resolve()
        } else if (frame.event === 'pusher:ping') {
          ws.send(JSON.stringify({ event: 'pusher:pong', data: {} }))
        } else if (frame.event === 'pusher_internal:subscription_succeeded' && frame.channel) {
          this.waiting.get(frame.channel)?.()
        } else if (frame.channel) {
          this.listeners.get(frame.channel)?.(frame.event, data)
        }
      })
    })
  }

  /** Joins a private channel; resolves once the server has confirmed it. */
  async subscribe(channel: string, listener: Listener): Promise<void> {
    const auth = await this.authorize(this.socketId, channel)
    this.listeners.set(channel, listener)

    await new Promise<void>((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`Subscribing to ${channel} was not confirmed within 10 s`)), 10_000)
      this.waiting.set(channel, () => { clearTimeout(timer); resolve() })
      this.ws?.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth } }))
    })
  }

  close(): void {
    this.ready = false
    this.ws?.close()
  }
}
