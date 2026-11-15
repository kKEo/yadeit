import type { ActionName } from './catalogue.ts'
import type { MemberPlan, RunConfig } from './control.ts'

export interface Persona {
  /** Mean pause between two actions, in simulated seconds, at intensity 1. */
  pause: number
  /** How often each action is chosen, relative to the others. Reading outweighs writing about ten to one. */
  weights: Partial<Record<ActionName, number>>
}

/**
 * The behaviour of each kind of team member. To add a behaviour: write the
 * action in actions.ts and give it a weight here.
 */
export const PERSONAS: Record<string, Persona> = {
  lead: {
    pause: 40,
    weights: {
      appStart: 2, openItems: 14, openMine: 4, filterItems: 4, openItem: 14, createItem: 4, moveItem: 3, editItem: 3, comment: 3,
      inbox: 6, search: 3, docs: 3, openDoc: 2, editDoc: 1, createDoc: 0.5, feedbackList: 3, triage: 2, members: 2, settings: 1,
      flowFeedback: 0.6, flowDoc: 0.3, flowIsolation: 0.3, delegate: 1.5,
    },
  },
  developer: {
    pause: 50,
    weights: {
      appStart: 2, openItems: 14, openMine: 8, filterItems: 3, openItem: 18, createItem: 2, moveItem: 6, editItem: 1, comment: 5,
      attach: 1, inbox: 6, search: 4, docs: 2, openDoc: 2, sendFeedback: 0.5,
      flowLifecycle: 1, flowNotify: 0.7, flowSearch: 0.5, flowIsolation: 0.2, flowRealtime: 0.6,
    },
  },
  designer: {
    pause: 60,
    weights: {
      appStart: 2, openItems: 12, openMine: 6, filterItems: 3, openItem: 16, createItem: 2, moveItem: 4, comment: 5, attach: 3,
      inbox: 5, search: 3, docs: 4, openDoc: 4, editDoc: 2, createDoc: 1, sendFeedback: 0.5,
      flowDoc: 0.8, flowLifecycle: 0.5,
    },
  },
  client: {
    pause: 120,
    weights: { appStart: 2, openItems: 10, openItem: 12, comment: 3, sendFeedback: 2, inbox: 4, search: 2, docs: 3, openDoc: 3, flowFeedback: 0.5, flowIsolation: 0.4 },
  },
}

/** Actions a guest's role does not allow; a guest persona never draws them. */
const NOT_FOR_GUESTS: ActionName[] = ['createItem', 'moveItem', 'editItem', 'attach', 'editDoc', 'createDoc', 'triage', 'feedbackList', 'settings', 'flowLifecycle', 'flowNotify', 'flowSearch', 'flowDoc', 'flowRealtime', 'delegate']
const ADMIN_ONLY: ActionName[] = ['settings']

/** The persona as a scenario wants it: its own pause and weights laid over the defaults. */
export function personaFor(name: string, overrides: RunConfig['personas'] = {}): Persona {
  const base = PERSONAS[name] ?? PERSONAS.developer!
  const own = overrides[name]
  return own ? { pause: own.pause ?? base.pause, weights: { ...base.weights, ...own.weights } } : base
}

export function weightsFor(persona: Persona, role: MemberPlan['role'], disabled: readonly string[] = []): Partial<Record<ActionName, number>> {
  const admin = role === 'owner' || role === 'admin'
  return Object.fromEntries(Object.entries(persona.weights).filter(([name]) =>
    !disabled.includes(name) && !(role === 'guest' && NOT_FOR_GUESTS.includes(name as ActionName)) && !(!admin && ADMIN_ONLY.includes(name as ActionName)),
  ))
}
