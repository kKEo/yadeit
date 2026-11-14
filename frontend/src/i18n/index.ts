import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import pl from './locales/pl.json'

export const SUPPORTED_LOCALES = ['en', 'pl'] as const
export type AppLocale = (typeof SUPPORTED_LOCALES)[number]

export const DEFAULT_LOCALE: AppLocale = 'en'
const STORAGE_KEY = 'yadeit_locale'

function isSupported(value: unknown): value is AppLocale {
  return SUPPORTED_LOCALES.includes(value as AppLocale)
}

function storedLocale(): AppLocale {
  try {
    const value = localStorage.getItem(STORAGE_KEY)
    if (isSupported(value)) return value
  } catch {
    // storage unavailable (private mode, blocked) — fall through
  }
  return DEFAULT_LOCALE
}

// Polish has three plural forms; messages use "zero | one | few | many".
function polishPlural(choice: number, choicesLength: number): number {
  if (choicesLength < 4) return Math.min(choice === 0 ? 0 : choice === 1 ? 1 : 2, choicesLength - 1)
  if (choice === 0) return 0
  if (choice === 1) return 1
  const mod10 = choice % 10
  const mod100 = choice % 100
  return mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14) ? 2 : 3
}

const i18n = createI18n({
  legacy: false,
  locale: storedLocale(),
  fallbackLocale: DEFAULT_LOCALE,
  messages: { en, pl },
  pluralRules: { pl: polishPlural },
})

export function setLocale(locale: AppLocale) {
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // ignore — the choice just won't persist
  }
}

document.documentElement.lang = i18n.global.locale.value

export default i18n
