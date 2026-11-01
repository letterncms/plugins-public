import { locale } from '@letterncms/sdk'

/** "Deutsch" for "de": a language's name in that language. */
export function nativeName(code: string): string {
  return display(code, code)
}

/** "German" for "de", in the admin's own language (not the browser's). */
export function localName(code: string): string {
  return display(code, locale())
}

function display(code: string, inLocale: string): string {
  try {
    const name = new Intl.DisplayNames([inLocale], { type: 'language' }).of(code) ?? code
    return name.charAt(0).toLocaleUpperCase(inLocale) + name.slice(1)
  } catch {
    return code
  }
}

/** Languages offered when adding one; any code matching xx or xx-YY also works. */
export const COMMON = [
  'ar', 'bg', 'ca', 'cs', 'da', 'de', 'de-AT', 'de-CH', 'el', 'en', 'en-GB', 'en-US', 'es', 'es-MX', 'et', 'fa', 'fi', 'fr', 'fr-CA', 'he', 'hi', 'hr', 'hu',
  'id', 'it', 'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'nl-BE', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'th', 'tr', 'uk', 'vi', 'zh-CN', 'zh-TW',
]
