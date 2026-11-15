import { CheckFailure, eventually, flow, SKIP } from './flows.ts'
import type { TeamContext } from './member.ts'
import type { Recorder } from './metrics.ts'
import { Rng, seedFor } from './rng.ts'
import type { Session } from './session.ts'

interface ToolResult { data: Record<string, unknown>; isError: boolean; text: string }

const PROTOCOL = '2026-07-28'

/**
 * A simulated AI agent: a team member that works through the MCP server with
 * an access token, the way a coding agent does. Each turn it takes one ready
 * item through the whole loop: claim, plan, question, link, submit for
 * review. Along the way it checks the guardrails agents are held to.
 */
export class Agent {
  due = 0
  busy = false
  current = 'idle'
  readonly plan: { id: number }
  readonly team: TeamContext
  readonly recorder: Recorder
  private readonly session: Session
  private readonly rng: Rng
  private readonly pause: number
  private readonly userId: number
  private calls = 0

  constructor(seat: TeamContext['agents'][number], team: TeamContext, session: Session, recorder: Recorder, runSeed: number, intensity: number) {
    this.plan = { id: seat.memberId }
    this.userId = seat.id
    this.team = team
    this.session = session
    this.session.token = seat.token
    this.recorder = recorder
    this.rng = new Rng(seedFor(runSeed, seat.memberId, 3))
    this.pause = 150 / intensity
  }

  next(): { action: 'agentRun'; pause: number } {
    return { action: 'agentRun', pause: Math.max(10, this.rng.pause(this.pause)) }
  }

  async act(): Promise<void> {
    const started = performance.now()
    this.current = 'agentRun'
    await this.work()
    this.recorder.action('agentRun', performance.now() - started, true)
    this.current = 'idle'
  }

  private work(): Promise<void> {
    return flow(this, 'agentRun', async (step) => {
      step('ready work')
      if (this.calls === 0) await this.tool('whoami')
      const ready = (await this.tool('ready_items', { limit: 5 })).data.items as { key: string }[] | undefined
      const key = this.rng.pick(ready ?? [])?.key
      if (!key) return SKIP

      step('claim')
      const claim = await this.tool('claim_item', { key }, true)
      // Another agent or a person took it in the same moment: nothing wrong, nothing to do.
      if (claim.isError) return SKIP

      step('second claim is refused')
      const rival = this.team.agents.find((other) => other.id !== this.userId)
      if (rival) {
        const second = await this.tool('claim_item', { key }, true, rival.token)
        if (!second.isError) throw new CheckFailure('Two agents hold the same item', { item: key })
      }

      step('plan')
      await this.tool('get_item', { key })
      await this.tool('log_progress', { key, type: 'thought', body: 'Plan: reproduce, change the smallest thing that fixes it, add a test.' })
      await this.tool('log_progress', { key, type: 'action', body: 'Reproduced locally and wrote a failing test.' })

      const helper = this.team.members.find((member) => member.signedIn && member.canWrite)
      if (helper && this.rng.chance(0.4)) {
        step('question')
        await this.tool('add_comment', { key, body: 'Should the old behaviour stay available behind a setting?', kind: 'question' })
        const path = `${helper.R}/items/${key}`
        const asked = (await helper.session.get<{ data: { needs_input: boolean } }>(path)).data.data
        if (!asked.needs_input) throw new CheckFailure('A question did not mark the item as waiting for a person', { item: key })

        step('answer')
        await helper.session.post(`${path}/comments`, { body: 'No, replace it.' })
        await eventually(5, 'The answer did not clear "waiting for a person"', async () =>
          !(await helper.session.get<{ data: { needs_input: boolean } }>(path)).data.data.needs_input)
      }

      step('link')
      await this.tool('link', { key, url: `https://example.com/pulls/${this.rng.int(100, 9999)}`, kind: 'pr', title: 'Pull request' })

      step('agents cannot close')
      const close = await this.tool('update_item', { key, state: 'Done' }, true)
      if (!close.isError) throw new CheckFailure('An agent could move an item to Done', { item: key })

      step('submit for review')
      const submitted = await this.tool('submit_for_review', { key, summary: 'Fixed and covered by a test. Nothing left open.' })
      const state = (submitted.data.state ?? (submitted.data.item as { state?: unknown } | undefined)?.state) as string | { name?: string } | undefined
      const name = typeof state === 'string' ? state : state?.name
      if (name !== undefined && name !== 'In review') throw new CheckFailure(`After submitting, the item is in "${name}", not "In review"`, { item: key })
    })
  }

  /** One tool call over MCP. A tool that reports an error throws, unless the caller expects that it may. */
  private async tool(name: string, args: Record<string, unknown> = {}, mayFail = false, token?: string): Promise<ToolResult> {
    this.calls += 1
    const own = this.session.token
    if (token) this.session.token = token

    try {
      const reply = await this.session.request<{ result?: { structuredContent?: Record<string, unknown>; isError?: boolean; content?: { text?: string }[] }; error?: { message?: string } }>(
        'POST', '/mcp', {
          name: `MCP ${name}`,
          body: {
            jsonrpc: '2.0', id: this.calls, method: 'tools/call',
            params: { name, arguments: args, _meta: { 'io.modelcontextprotocol/protocolVersion': PROTOCOL, 'io.modelcontextprotocol/clientCapabilities': {} } },
          },
          headers: { Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': PROTOCOL, 'Mcp-Method': 'tools/call', 'Mcp-Name': name },
        }, '')

      const result = reply.data.result
      if (!result) throw new CheckFailure(`The MCP server did not answer ${name}: ${reply.data.error?.message ?? 'no result'}`)

      const answer = { data: result.structuredContent ?? {}, isError: result.isError === true, text: result.content?.[0]?.text ?? '' }
      if (answer.isError && !mayFail) throw new CheckFailure(`Tool ${name} failed: ${answer.text.slice(0, 300)}`)
      return answer
    } finally {
      this.session.token = own
    }
  }
}
