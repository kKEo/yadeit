<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useUiStore } from '@/stores/ui'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import DropdownMenuLabel from '@/components/ui/DropdownMenuLabel.vue'
import DropdownMenuSeparator from '@/components/ui/DropdownMenuSeparator.vue'
import { CircleUser, Keyboard, LogOut, UserCog } from '@lucide/vue'

const auth = useAuthStore()
const ui = useUiStore()
const router = useRouter()
const { t } = useI18n()

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <DropdownMenu v-if="auth.user" align="end">
    <template #trigger>
      <Button variant="ghost" size="sm" class="gap-2" :aria-label="t('userMenu.label')">
        <CircleUser class="h-5 w-5" />
        <span class="hidden max-w-[10rem] truncate sm:inline">{{ auth.user.name }}</span>
      </Button>
    </template>
    <DropdownMenuLabel>
      <span class="block truncate text-sm font-medium text-foreground">{{ auth.user.name }}</span>
      <span class="block truncate font-normal">{{ auth.user.email }}</span>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuItem @select="ui.profileOpen = true">
      <UserCog class="h-4 w-4" />{{ t('profile.title') }}
    </DropdownMenuItem>
    <DropdownMenuItem @select="ui.shortcutsHelpOpen = true">
      <Keyboard class="h-4 w-4" />{{ t('shortcuts.title') }}
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem @select="signOut">
      <LogOut class="h-4 w-4" />{{ t('common.signOut') }}
    </DropdownMenuItem>
  </DropdownMenu>
</template>
