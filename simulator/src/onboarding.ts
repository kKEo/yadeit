import type { ItemResponse, Workspace } from '../../frontend/src/api/types.ts'
import type { Control } from './control.ts'
import type { Member } from './member.ts'

/**
 * A team that starts from nothing, the way real teams do: the lead signs up,
 * confirms the address from the email, creates the workspace and invites the
 * others; each of them follows the invitation, creates an account and joins.
 */
export async function signUpTeam(control: Control, members: Member[], teamId: number): Promise<void> {
  const [lead, ...others] = members
  if (!lead) return

  await register(lead)
  const verify = new URL(await control.link(lead.plan.email, 'VerifyEmail', '/verify-email'))
  const q = verify.searchParams
  await lead.session.get(`/auth/verify-email/${q.get('id')}/${q.get('hash')}?expires=${q.get('expires')}&signature=${q.get('signature')}`)

  const workspace = (await lead.session.post<ItemResponse<Workspace>>('/workspaces', { name: lead.team.name })).data.data
  lead.team.slug = workspace.slug
  lead.signedIn = true

  for (const member of others) {
    await lead.session.post(`/workspaces/${workspace.slug}/invitations`, { email: member.plan.email, role: member.plan.role })

    const token = (await control.link(member.plan.email, 'WorkspaceInvitation', '/invitations/')).split('/invitations/')[1]!
    await member.session.get(`/invitations/${token}`)
    await register(member)
    await member.session.post(`/invitations/${token}/accept`)
    await member.session.get('/me')
    member.signedIn = true
  }

  const { status } = await control.teamReady(teamId)
  if (status !== 'ready') throw new Error(`The coordinator does not see the team's workspace (status ${status})`)
}

async function register(m: Member): Promise<void> {
  await m.session.csrfCookie()
  await m.session.post('/auth/register', {
    name: m.plan.name, email: m.plan.email, password: m.plan.password, password_confirmation: m.plan.password, locale: 'en',
  })
  await m.session.get('/me')
}
