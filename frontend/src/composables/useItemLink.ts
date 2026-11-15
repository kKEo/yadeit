import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSlug } from '@/queries/keys'
import { useToastStore } from '@/stores/toast'

/** The shareable address of an item's full page, and copying it. */
export function useItemLink() {
  const router = useRouter()
  const slug = useSlug()
  const toast = useToastStore()
  const { t } = useI18n()

  const itemLink = (key: string) => `${window.location.origin}${router.resolve({ name: 'item-detail', params: { slug: slug.value, key } }).href}`

  async function copyLink(key: string) {
    try {
      await navigator.clipboard.writeText(itemLink(key))
      toast.success(t('commands.linkCopied'))
    } catch {
      toast.error(t('commands.copyFailed'))
    }
  }

  return { itemLink, copyLink }
}
