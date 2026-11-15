import { BASIC } from './actions.ts'
import { FLOWS } from './flows.ts'

/** Everything a member can be asked to do: single actions, and flows that check a whole journey. */
export const ACTIONS = { ...BASIC, ...FLOWS }

export type ActionName = Exclude<keyof typeof ACTIONS, 'signIn'>
