import type { DocPage, ItemResponse } from '../../frontend/src/api/types.ts'
import { BASIC, loadProjects } from './actions.ts'
import type { MemberPlan } from './control.ts'
import { CheckFailure, flow } from './flows.ts'
import type { Member } from './member.ts'

/**
 * What a lead does once, before the team gets going: creates the team's
 * agents with a token each, points a webhook at the lab's receiver, opens
 * the public feedback form, shares one page with the public, and switches
 * cycles and staleness on. Everything goes through the API, as a person
 * would do it in the web app.
 */
export function setUpTeam(lead: Member, agents: MemberPlan[]): Promise<void> {
  return flow(lead, 'teamSetup', async (step) => {
    step('sign in')
    if (!lead.signedIn) await BASIC.signIn(lead)
    else if (lead.me === null) await BASIC.appStart(lead)

    const projects = await loadProjects(lead, true)
    const project = projects.find((p) => p.key !== 'DEMO') ?? projects[0]
    if (!project) throw new CheckFailure('The workspace has no project')

    step('planning')
    for (const p of projects.filter((p) => p.cycle_weeks === null)) {
      await lead.session.patch(`${lead.R}/projects/${p.key}`, { cycle_weeks: 1, stale_after_days: 7 })
    }

    step('webhook')
    await lead.session.post(`${lead.R}/webhooks`, { name: 'Traffic lab', url: lead.team.sinkUrl })

    step('public feedback form')
    const form = await lead.session.request<ItemResponse<{ feedback_token: string | null }>>('PUT', `${lead.R}/projects/${project.key}/feedback-form`, { body: { enabled: true } })
    lead.team.feedbackToken = form.data.data.feedback_token

    step('public page')
    const created = (await lead.session.post<ItemResponse<DocPage>>(`${lead.R}/pages`, { title: 'What we are working on', body: 'A page anyone with the link can read.' })).data.data
    const shared = (await lead.session.patch<ItemResponse<DocPage>>(`${lead.R}/pages/${created.id}`, { status: 'published', public: true, expected_version: created.version })).data.data
    if (shared.public_token) lead.team.publicPages.push(shared.public_token)

    step('agents')
    for (const plan of agents) {
      const agent = (await lead.session.post<ItemResponse<{ id: number }>>(`${lead.R}/agents`, { name: plan.name, description: 'Plays an AI coding agent in a simulation.' })).data.data
      const token = (await lead.session.post<ItemResponse<{ token: string }>>(`${lead.R}/agents/${agent.id}/tokens`, { name: 'Traffic lab', access: 'write' })).data.data
      lead.team.agents.push({ id: agent.id, memberId: plan.id, name: plan.name, token: token.token, header: plan.header })
    }
  })
}
