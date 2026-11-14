import { request, workspacePath } from './client'
import type { BillingStatus, ItemResponse } from './types'

const billingPath = (slug: string) => `${workspacePath(slug)}/billing`

export async function getBilling(slug: string): Promise<BillingStatus> {
  return (await request<ItemResponse<BillingStatus>>('GET', billingPath(slug))).data
}

/** The address of the payment page for moving the workspace to a paid plan. */
export async function startCheckout(slug: string, plan: string): Promise<string> {
  return (await request<ItemResponse<{ url: string }>>('POST', `${billingPath(slug)}/checkout`, { body: { plan } })).data.url
}

/** The address of the billing portal: invoices, payment method, cancelling. */
export async function openPortal(slug: string): Promise<string> {
  return (await request<ItemResponse<{ url: string }>>('POST', `${billingPath(slug)}/portal`)).data.url
}
